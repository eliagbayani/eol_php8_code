"""
Class: blueprint for creating new objects
Object: instance of a class
e.g.
Class: Human
Objects: John, Marry, Jack

Class attributes = declared in the Class itself
Instance attributes = declared outside of the Class

"""


class Point:
    default_color = "Blue"  # a Class attribute/variable

    def __init__(self, x, y):  # this magic method is called a constructor. An Instance method
        self.x = x  # an Instance attribute/variable
        self.y = y

    def __str__(self):  # here we redo what the default __str__ does
        return f"new implementation: ({self.x}, {self.y})"  # now it does this

    @classmethod  # called a decorator
    def zero(cls):  # a Class method. "cls" is user-defined
        return cls(0, 173)

    def draw(self):  # an Instance method
        print(f"Point({self.x}, {self.y})")


point_0 = Point.zero()  # also called a factory method
point_0.draw()
print(point_0)  # this now displays the result of the class method __str__()

point = Point(1, 2)
print(point.default_color)
print(Point.default_color)
point.draw()

Point.default_color = "Yellow"
point2 = Point(3, 4)
print(Point.default_color)
point2.draw()
print(str(point2))

# available methods to check:
# print(type(point))
# print(isinstance(point, Point))
