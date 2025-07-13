class Point:

    def __init__(self, x, y):  # this magic method is called a constructor. An Instance method
        self.x = x  # an Instance attribute/variable
        self.y = y

    def __eq__(self, otherx):
        return self.x == otherx.x and self.y == otherx.y

    def __gt__(self, otherx):
        return self.x > otherx.x and self.y > otherx.y


point = Point(1, 2)
point2 = Point(1, 2)
print(point == point2)


point = Point(10, 20)
point2 = Point(1, 2)
print(point > point2)

Next: Performing Arithmetic Operations
